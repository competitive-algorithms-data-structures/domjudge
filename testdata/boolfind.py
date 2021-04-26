import random
import sys
number_of_testcases = random.randint(1,10)
debug=True
print(number_of_testcases)
for testcase_number in range(number_of_testcases):
    found = False
    length_of_string = random.randint(2,10)
    print(length_of_string)
    max_number = 2**length_of_string-1
    boolean_array = [int(x) for x in list(format(max_number-1,'b'))]
    if debug:
        print(','.join([str(x) for x in boolean_array]), file=sys.stderr)
    while not found:
        read,number = input().split()
        number = int(number)
        if read=="READ":
            assert number<length_of_string
            output_bool = "true" if boolean_array[number] else "false"
            print(output_bool)
        elif read=="OUTPUT":
            assert number+1<length_of_string
            assert number>=0
            assert boolean_array[number]==1 and boolean_array[number+1]==0
            found=True
